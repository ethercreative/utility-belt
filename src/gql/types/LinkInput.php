<?php

namespace ether\utilitybelt\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class LinkInput extends InputObjectType
{

	public static string $typeName = 'LinkInput';

	public function getName (): string
	{
		return self::$typeName;
	}

	public static function getType (): InputObjectType
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
				'urlSuffix'   => Type::string(),
			],
		]);
	}

}