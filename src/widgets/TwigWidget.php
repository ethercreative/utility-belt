<?php

namespace ether\utilitybelt\widgets;

use Craft;
use craft\base\Widget;
use craft\web\View;

class TwigWidget extends Widget
{

	public string $title = '';
	public string $template = '';

	public static function displayName (): string
	{
		return 'Twig';
	}

	public function getSettingsHtml (): ?string
	{
		return Craft::$app->getView()->renderTemplate(
			'utility-belt/widgets/twig-settings',
			['widget' => $this]
		);
	}

	public function getTitle (): string
	{
		return $this->title ?? parent::getTitle();
	}

	public function getBodyHtml (): ?string
	{
		if (empty($this->template))
			return '';

		$view = Craft::$app->getView();
		$templateMode = $view->getTemplateMode();
		$view->setTemplateMode(View::TEMPLATE_MODE_SITE);
		$markup = $view->renderTemplate($this->template);
		$view->setTemplateMode($templateMode);

		return $markup;
	}

}
