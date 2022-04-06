<?php

namespace ether\utilitybelt\models;

use craft\base\ElementInterface;
use craft\base\Model;
use ether\utilitybelt\fields\LinkField;

class LinkModel extends Model
{

	public string|ElementInterface|null $type = null;

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
		if (empty($this->elementId) || in_array($this->type, LinkField::NON_ELEMENT_TYPES))
			return null;

		return $this->type::findOne($this->elementId);
	}

	public function isEmpty (): bool
	{
		return empty($this->elementId) && empty($this->customUrl);
	}

	public function getUrl (): ?string
	{
		return empty($this->elementId) ? $this->customUrl : $this->elementUrl;
	}

	public function getText (): ?string
	{
		if (!empty($this->elementId) && empty($this->customText))
			return $this->elementText;

		return $this->customText;
	}

}