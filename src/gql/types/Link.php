<?php

namespace ether\utilitybelt\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\Type;

class Link extends ObjectType
{

	public static string $typeName = 'Link';

	public function getName (): string
	{
		return self::$typeName;
	}

	public static function getType (): Type
	{
		return GqlEntityRegistry::getEntity(self::$typeName) ?: GqlEntityRegistry::createEntity(self::$typeName, new self());
	}

	public function __construct ()
	{
		parent::__construct([
			'fields' => [
				'type'        => Type::string(),
				'customText'  => Type::string(),
				'customUrl'   => Type::string(),
				'elementText' => Type::string(),
				'elementUrl'  => Type::string(),
				'elementId'   => Type::id(),
				'element'     => Element::getType(),

				'url'         => Type::string(),
				'text'        => Type::string(),
			],
		]);
	}

}