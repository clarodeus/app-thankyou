<?php

namespace Claromentis\ThankYou\Controller\Rest;

use Claromentis\Core\Config\WritableConfig;
use Claromentis\Core\Http\JsonPrettyResponse;
use Claromentis\Core\Http\ResponseFactory;
use Claromentis\Core\Localization\Lmsg;
use Claromentis\Core\Security\SecurityContext;
use Claromentis\ThankYou\Api;
use Claromentis\ThankYou\Configuration\Configuration;
use Claromentis\ThankYou\Exception\ThankYouAuthor;
use Claromentis\ThankYou\Exception\ThankYouException;
use Claromentis\ThankYou\Exception\ThankYouForbidden;
use Claromentis\ThankYou\Exception\ThankYouNotFound;
use Claromentis\ThankYou\Exception\ThankYouOClass;
use Claromentis\ThankYou\Exception\ThankYouRepository;
use Claromentis\ThankYou\Tags\Exceptions\TagDuplicateNameException;
use Claromentis\ThankYou\Tags\Exceptions\TagException;
use Claromentis\ThankYou\Tags\Exceptions\TagInvalidNameException;
use Claromentis\ThankYou\Tags\Exceptions\TagNotFound;
use Claromentis\ThankYou\Tags\Tag;
use Date;
use DateClaTimeZone;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RestExBadRequest;
use RestExError;
use RestExNotFound;
use RestFormat;

class ThanksRestV2
{
	/**
	 * @var Api
	 */
	private $api;

	/**
	 * @var WritableConfig
	 */
	private $config;

	/**
	 * @var Lmsg
	 */
	private $lmsg;

	/**
	 * @var LoggerInterface
	 */
	private $log;

	/**
	 * @var ResponseFactory
	 */
	private $response;

	/**
	 * @var RestFormat
	 */
	private $rest_format;

	/**
	 * ThanksRestV2 constructor.
	 *
	 * @param Api             $api
	 * @param ResponseFactory $response_factory
	 * @param LoggerInterface $logger
	 * @param RestFormat      $rest_format
	 * @param Lmsg            $lmsg
	 * @param WritableConfig  $config
	 */
	public function __construct(Api $api, ResponseFactory $response_factory, LoggerInterface $logger, RestFormat $rest_format, Lmsg $lmsg, WritableConfig $config)
	{
		$this->api         = $api;
		$this->config      = $config;
		$this->lmsg        = $lmsg;
		$this->log         = $logger;
		$this->response    = $response_factory;
		$this->rest_format = $rest_format;
	}

	/**
	 * @param int             $id
	 * @param SecurityContext $security_context
	 * @return JsonPrettyResponse
	 * @throws RestExNotFound - If the Thank You could not be found.
	 * @throws RestExError - If the Thank You could not be created.
	 */
	public function GetThankYou(int $id, SecurityContext $security_context): JsonPrettyResponse
	{
		try
		{
			$thank_you = $this->api->ThankYous()->GetThankYous($id, true);
		} catch (ThankYouNotFound $exception)
		{
			throw new RestExNotFound(($this->lmsg)('thankyou.error.thanks_not_found'), "Not found", $exception);
		} catch (ThankYouOClass $exception)
		{
			throw new RestExError(($this->lmsg)('thankyou.thankyou.error.server'), "Internal Server Error", $exception);
		}

		$display_thank_you = $this->api->ThankYous()->ConvertThankYousToArrays($thank_you, DateClaTimeZone::GetCurrentTZ(), $security_context);

		return $this->response->GetJsonPrettyResponse($display_thank_you);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param SecurityContext        $security_context
	 * @return JsonPrettyResponse
	 * @throws RestExError - If the Thank You could not be created.
	 */
	public function GetThankYous(ServerRequestInterface $request, SecurityContext $security_context): JsonPrettyResponse
	{
		$query_params = $request->getQueryParams();
		$limit        = $query_params['limit'] ?? 20;
		$offset       = $query_params['offset'] ?? 0;
		$thanked      = (bool) (int) ($query_params['thanked'] ?? null);

		try
		{
			$thank_yous = $this->api->ThankYous()->GetRecentThankYous($limit, $offset, $thanked);
		} catch (ThankYouOClass $exception)
		{
			throw new RestExError(($this->lmsg)('thankyou.thankyou.error.server'), "Internal Server Error", $exception);
		}
		$display_thank_yous = $this->api->ThankYous()->ConvertThankYousToArrays($thank_yous, DateClaTimeZone::GetCurrentTZ(), $security_context);

		return $this->response->GetJsonPrettyResponse($display_thank_yous);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param SecurityContext        $context
	 * @return JsonPrettyResponse
	 * @throws RestExBadRequest
	 */
	public function CreateThankYou(ServerRequestInterface $request, SecurityContext $context): JsonPrettyResponse
	{
		$post = $this->rest_format->GetJson($request);

		if (!isset($post))
		{
			throw new RestExBadRequest();
		}

		$thanked     = (array) ($post['thanked'] ?? null);
		$description = (string) ($post['description'] ?? null);
		$tag_ids     = $post['tags'] ?? null;

		$invalid_params = [];

		foreach ($thanked as $offset => $oclass)
		{
			$thanked[$offset] = ['oclass' => (int) ($oclass['oclass'] ?? null), 'id' => (int) ($oclass['id'] ?? null)];
		}

		try
		{
			$thankables = $this->api->ThankYous()->CreateThankablesFromOClasses($thanked);

			if (count($thankables) === 0)
			{
				$invalid_params[] = ['name' => 'thanked', 'reason' => ($this->lmsg)('thankyou.thankyou.thanked.error.empty')];
			}
		} catch (ThankYouOClass $exception)
		{
			$message = ($this->lmsg)('thankyou.thankable.owner_class.error.not_supported');
			$first   = true;
			foreach ($this->api->ThankYous()->GetThankableObjectTypes() as $owner_class)
			{
				$message .= $first ? $owner_class : ", " . $owner_class;
				$first   = false;
			}
			$invalid_params[] = ['name' => 'thanked', 'reason' => $message];
		}

		if ($description === '')
		{
			$invalid_params[] = ['name' => 'description', 'reason' => ($this->lmsg)('thankyou.thankyou.description.error.empty')];
		}

		if (isset($tag_ids))
		{
			if ($this->config->Get('thankyou_core_values_enabled') !== true)
			{
				$invalid_params[] = ['name' => 'tags', 'reason' => ($this->lmsg)('thankyou.thankyou.tags.error.disabled')];
			} else
			{
				if (!is_array($tag_ids))
				{
					$invalid_params[] = ['name' => 'tags', 'reason' => ($this->lmsg)('thankyou.thankyou.tags.error.not_array')];
				}

				try
				{
					$tags = $this->api->Tag()->GetTagsById($tag_ids);

					foreach ($tag_ids as $tag_id)
					{
						if (!isset($tags[$tag_id]))
						{
							$invalid_params[] = ['name' => 'tags', 'reason' => ($this->lmsg)('thankyou.tag.error.id.not_found', $tag_id)];
						}
					}
				} catch (TagException $exception)
				{
					$invalid_params[] = ['name' => 'tags', 'reason' => ($this->lmsg)('thankyou.thankyou.tags.error.not_integers')];
				}
			}
		} elseif ($this->config->Get('thankyou_core_values_mandatory') === true)
		{
			$invalid_params[] = ['name' => 'tags', 'reason' => ($this->lmsg)('thankyou.thankyou.core_values.empty')];
		}

		if (count($invalid_params) > 0)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.thankyou.error.create'),
				'status'         => 400,
				'invalid-params' => $invalid_params
			], 400);
		}

		try
		{
			$thank_you = $this->api->ThankYous()->Create($context->GetUser(), $description, null);
			$thank_you->SetThanked($thankables);
			if (isset($tags))
			{
				$thank_you->SetTags($tags);
			}
			$this->api->ThankYous()->PopulateThankYouUsersFromThankables($thank_you);
			$this->api->ThankYous()->Save($thank_you);
			$this->api->ThankYous()->Notify($thank_you);
		} catch (ThankYouNotFound $exception)
		{
			throw new LogicException("Unexpected Exception thrown when creating Thank You", null, $exception);
		} catch (ThankYouOClass $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.thankyou.error.create'),
				'status'         => 400,
				'invalid-params' => [['name' => 'thanked', 'reason' => ($this->lmsg)('thankyou.thankable.error.unsuitable_owner_classes')]]
			], 400);
		} catch (ThankYouAuthor | ThankYouRepository $exception)
		{
			$this->log->error("CreateThankYou Failed unexpectedly", [$exception]);

			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.thankyou.error.create'),
				'status' => 500
			], 500);
		} catch (ThankYouException $exception)
		{
			$this->log->error("A Thank You was created but there was an error sending notifications", [$exception]);
		}

		return $this->response->GetJsonPrettyResponse(true);
	}

	/**
	 * @param int                    $id
	 * @param ServerRequestInterface $request
	 * @param SecurityContext        $context
	 * @return JsonPrettyResponse
	 * @throws RestExBadRequest
	 */
	public function UpdateThankYou(int $id, ServerRequestInterface $request, SecurityContext $context): JsonPrettyResponse
	{
		$post = $this->rest_format->GetJson($request);

		if (!isset($post))
		{
			throw new RestExBadRequest();
		}

		$invalid_params = [];

		try
		{
			$thank_you = $this->api->ThankYous()->GetThankYou($id, false, false);

			if (!$this->api->ThankYous()->CanEditThankYou($thank_you, $context))
			{
				return $this->response->GetJsonPrettyResponse([
					'type'   => 'https://developer.claromentis.com',
					'title'  => ($this->lmsg)('thankyou.error.no_edit_permission'),
					'status' => 401
				], 401);
			}

			$description = $post['description'] ?? null;

			if (isset($description))
			{
				if (!is_string($description))
				{
					$invalid_params[] = ['name' => 'description', 'reason' => ($this->lmsg)('thankyou.thankyou.description.error.not_string')];
				} elseif ($description === '')
				{
					$invalid_params[] = ['name' => 'description', 'reason' => ($this->lmsg)('thankyou.thankyou.description.error.empty')];
				} else
				{
					$thank_you->SetDescription($description);
				}
			}

			$thanked = $post['thanked'] ?? null;

			if (isset($thanked))
			{
				if (!is_array($thanked))
				{
					$invalid_params[] = ['name' => 'thanked', 'reason' => ($this->lmsg)('thankyou.thankyou.thanked.error.not_array')];
				} elseif (count($thanked) === 0)
				{
					$invalid_params[] = ['name' => 'thanked', 'reason' => ($this->lmsg)('thankyou.thankyou.thanked.error.empty')];
				} else
				{
					foreach ($thanked as $offset => $oclass)
					{
						$thanked[$offset] = ['oclass' => (int) ($oclass['oclass'] ?? null), 'id' => (int) ($oclass['id'] ?? null)];
					}

					try
					{
						$thankables = $this->api->ThankYous()->CreateThankablesFromOClasses($thanked);
						$thank_you->SetThanked($thankables);
						$this->api->ThankYous()->PopulateThankYouUsersFromThankables($thank_you);
					} catch (ThankYouOClass $exception)
					{
						$message = ($this->lmsg)('thankyou.thankable.owner_class.error.not_supported');
						$first   = true;
						foreach ($this->api->ThankYous()->GetThankableObjectTypes() as $owner_class)
						{
							$message .= $first ? $owner_class : ", " . $owner_class;
							$first   = false;
						}
						$invalid_params[] = ['name' => 'thanked', 'reason' => $message];
					}
				}
			}

			if (count($invalid_params) > 0)
			{
				return $this->response->GetJsonPrettyResponse([
					'type'           => 'https://developer.claromentis.com',
					'title'          => ($this->lmsg)('thankyou.thankyou.error.modify'),
					'status'         => 400,
					'invalid-params' => $invalid_params
				], 400);
			}

			$this->api->ThankYous()->Save($thank_you);
		} catch (ThankYouNotFound $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.error.thanks_not_found'),
				'status' => 404
			], 404);
		} catch (ThankYouOClass $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.thankyou.error.modify'),
				'status'         => 400,
				'invalid-params' => [['name' => 'thanked', 'reason' => ($this->lmsg)('thankyou.thankable.error.unsuitable_owner_classes')]]
			], 400);
		} catch (ThankYouRepository $exception)
		{
			$this->log->error("CreateThankYou Failed unexpectedly", [$exception]);

			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.thankyou.error.modify'),
				'status' => 500
			], 500);
		}

		return $this->response->GetJsonPrettyResponse(true);
	}

	/**
	 * @param int             $id
	 * @param SecurityContext $context
	 * @return JsonPrettyResponse
	 */
	public function DeleteThankYou(int $id, SecurityContext $context): JsonPrettyResponse
	{
		try
		{
			$this->api->ThankYous()->Delete($context, $id);
		} catch (ThankYouForbidden $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.error.no_edit_permission'),
				'status' => 401
			], 401);
		} catch (ThankYouNotFound $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.error.thanks_not_found'),
				'status' => 404
			], 404);
		}

		return $this->response->GetJsonPrettyResponse(true);
	}

	/**
	 * @param int $id
	 * @return JsonPrettyResponse
	 * @throws RestExNotFound
	 */
	public function GetTag(int $id): JsonPrettyResponse
	{
		try
		{
			$tag = $this->api->Tag()->GetTag($id);
		} catch (TagNotFound $exception)
		{
			throw new RestExNotFound(($this->lmsg)('thankyou.tag.error.id.not_found', $id), "Not Found", $exception);
		}

		$tag_display = $this->ConvertTagsToArray([$tag])[0];

		return $this->response->GetJsonPrettyResponse($tag_display);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return JsonPrettyResponse
	 */
	public function GetTags(ServerRequestInterface $request): JsonPrettyResponse
	{
		$query_params = $request->getQueryParams();
		$limit        = (int) ($query_params['limit'] ?? 20);
		$name         = $query_params['name'] ?? null;
		$offset       = (int) ($query_params['offset'] ?? 0);

		$tags = $this->api->Tag()->GetTags($limit, $offset, $name, [['column' => 'name']]);

		$tags_display = $this->ConvertTagsToArray($tags);

		return $this->response->GetJsonPrettyResponse($tags_display);
	}

	/**
	 * @return JsonPrettyResponse
	 */
	public function GetTotalTags(): JsonPrettyResponse
	{
		return $this->response->GetJsonPrettyResponse($this->api->Tag()->GetTotalTags());
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param SecurityContext        $security_context
	 * @return JsonPrettyResponse
	 * @throws RestExBadRequest
	 */
	public function CreateTag(ServerRequestInterface $request, SecurityContext $security_context): JsonPrettyResponse
	{
		$post = $this->rest_format->GetJson($request);

		if (!isset($post))
		{
			throw new RestExBadRequest();
		}

		$invalid_params = [];

		$name      = $post['name'] ?? null;
		$bg_colour = (isset($post['bg_colour']) && $post['bg_colour'] !== '') ? $post['bg_colour'] : null;

		if (!isset($name))
		{
			$invalid_params[] = ['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.undefined')];
		} elseif (!is_string($name))
		{
			$invalid_params[] = ['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.invalid')];
		}

		if (isset($bg_colour) && !is_string($bg_colour))
		{
			$invalid_params[] = ['name' => 'background_colour', 'reason' => ($this->lmsg)('thankyou.tag.error.background.invalid')];
		}

		if (count($invalid_params) > 0)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.tag.error.create'),
				'status'         => 400,
				'invalid-params' => $invalid_params
			], 400);
		}

		try
		{
			$tag = $this->api->Tag()->Create($security_context->GetUser(), $name);
			$tag->SetBackgroundColour($bg_colour);
			$this->api->Tag()->Save($tag);
			$response = $this->ConvertTagsToArray([$tag->GetId() => $tag]);
		} catch (TagDuplicateNameException $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.tag.error.create'),
				'status'         => 400,
				'invalid-params' => [['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.not_unique')]]
			], 400);
		} catch (TagInvalidNameException $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.tag.error.create'),
				'status'         => 400,
				'invalid-params' => [['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.invalid')]]
			], 400);
		}

		return $this->response->GetJsonPrettyResponse($response, 200);
	}

	/**
	 * @param int                    $id
	 * @param ServerRequestInterface $request
	 * @param SecurityContext        $security_context
	 * @return JsonPrettyResponse
	 * @throws RestExBadRequest
	 */
	public function UpdateTag(int $id, ServerRequestInterface $request, SecurityContext $security_context): JsonPrettyResponse
	{
		$post = $this->rest_format->GetJson($request);

		if (!isset($post))
		{
			throw new RestExBadRequest();
		}

		$invalid_params = [];

		try
		{
			$tag = $this->api->Tag()->GetTag($id);

			$active            = $post['active'] ?? null;
			$name              = $post['name'] ?? null;
			$bg_colour_defined = array_key_exists('bg_colour', $post);
			$bg_colour         = (isset($post['bg_colour']) && $post['bg_colour'] !== '') ? $post['bg_colour'] : null;

			if (isset($active) && is_bool($active))
			{
				$tag->SetActive($active);
			}

			if (isset($name))
			{
				if (!is_string($name))
				{
					$invalid_params[] = ['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.invalid')];
				} else
				{
					try
					{
						$tag->SetName($name);
					} catch (TagInvalidNameException $exception)
					{
						$invalid_params[] = ['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.not_empty')];
					}
				}
			}

			if (isset($bg_colour) && !is_string($bg_colour))
			{
				$invalid_params[] = ['name' => 'bg_colour', 'reason' => ($this->lmsg)('thankyou.tag.error.background.invalid')];
			}

			if ($bg_colour_defined)
			{
				$tag->SetBackgroundColour($bg_colour);
			}

			$tag->SetModifiedBy($security_context->GetUser());
			$tag->SetModifiedDate(new Date());

			if (count($invalid_params) > 0)
			{
				return $this->response->GetJsonPrettyResponse([
					'type'           => 'https://developer.claromentis.com',
					'title'          => ($this->lmsg)('thankyou.tag.error.modify'),
					'status'         => 400,
					'invalid-params' => $invalid_params
				], 400);
			}

			$this->api->Tag()->Save($tag);

			$response = $this->ConvertTagsToArray([$tag->GetId() => $tag]);
		} catch (TagNotFound $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'   => 'https://developer.claromentis.com',
				'title'  => ($this->lmsg)('thankyou.tag.error.id.not_found', $id),
				'status' => 404
			], 404);
		} catch (TagDuplicateNameException $exception)
		{
			return $this->response->GetJsonPrettyResponse([
				'type'           => 'https://developer.claromentis.com',
				'title'          => ($this->lmsg)('thankyou.tag.error.modify'),
				'status'         => 400,
				'invalid-params' => [['name' => 'name', 'reason' => ($this->lmsg)('thankyou.tag.error.name.not_unique')]]
			], 400);
		}

		return $this->response->GetJsonPrettyResponse($response, 200);
	}

	//TODO: Add a Delete route for tags...

	/**
	 * @param ServerRequestInterface $request
	 * @param Configuration          $configuration
	 * @return JsonPrettyResponse
	 * @throws RestExBadRequest
	 */
	public function SetConfig(ServerRequestInterface $request, Configuration $configuration): JsonPrettyResponse
	{
		$post = $this->rest_format->GetJson($request);

		$options = $configuration->GetOptions();

		foreach ($post as $config_name => $value)
		{
			if (!isset($options[$config_name]))
			{
				throw new RestExBadRequest();
			}

			$config = $options[$config_name];

			if (isset($config['type']) && $config['type'] === 'bool' && !is_bool($value))
			{
				throw new RestExBadRequest();
			}

			$this->config->Set($config_name, $value);
		}

		$this->api->Configuration()->SaveConfig($this->config);

		return $this->response->GetJsonPrettyResponse(true);
	}

	/**
	 * @param Tag[] $tags
	 * @return array
	 */
	private function ConvertTagsToArray(array $tags): array
	{
		$display_tags = [];
		foreach ($tags as $offset => $tag)
		{
			if (!($tag instanceof Tag))
			{
				throw new InvalidArgumentException("Failed to Convert Tags to an array, input must be an array of Tags only");
			}

			$created_date = clone $tag->GetCreatedDate();
			$created_date->setTimezone(DateClaTimeZone::GetCurrentTZ());
			$created_date = $this->rest_format->Date($created_date);

			$modified_date = clone $tag->GetModifiedDate();
			$modified_date->setTimezone(DateClaTimeZone::GetCurrentTZ());
			$modified_date = $this->rest_format->Date($modified_date);

			$created_by_name = null;
			$created_by      = $tag->GetCreatedBy();
			if ($created_by)
			{
				$created_by_name = $created_by->GetFullname();
			}

			$modified_by_name = null;
			$modified_by      = $tag->GetModifiedBy();
			if ($modified_by)
			{
				$modified_by_name = $modified_by->GetFullname();
			}

			$display_tags[$offset] = [
				'id'            => $tag->GetId(),
				'active'        => $tag->GetActive(),
				'name'          => $tag->GetName(),
				'created_by'    => $created_by_name,
				'created_date'  => $created_date,
				'modified_by'   => $modified_by_name,
				'modified_date' => $modified_date,
				'bg_colour'     => $tag->GetBackgroundColour()
			];
		}

		return $display_tags;
	}
}
