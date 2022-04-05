<?php

namespace ether\utilitybelt\models;

use craft\base\ElementInterface;
use craft\base\Model;

class LinkModel extends Model
{

	public ?string $type = null;

	public ?string $customText = null;
	public ?string $customUrl  = null;

	public ?string $elementText = null;
	public ?string $elementUrl  = null;
	public ?int    $elementId   = null;

	public function __construct ($config = [])
	{
		if (empty($config['elementId']))
			$config['elementId'] = null;

		parent::__construct($config);
	}

	public function getElement (): ?ElementInterface
	{
		if (empty($this->elementId) || in_array($this->type, ['custom', 'url']))
			return null;

		return $this->type::findOne($this->elementId);
	}

}