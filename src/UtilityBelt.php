<?php

namespace ether\utilitybelt;

use Craft;
use craft\base\Plugin;
use craft\events\ExecuteGqlQueryEvent;
use craft\events\PluginEvent;
use craft\helpers\App;
use craft\helpers\Json;
use craft\services\Gql;
use craft\services\Plugins;
use ether\utilitybelt\services\LivePreview;
use ether\utilitybelt\services\Revalidator;
use yii\base\Event;

/**
 * @property LivePreview $livePreview
 * @property Revalidator $revalidator
 */
class UtilityBelt extends Plugin
{

	public function init ()
	{
		$this->setComponents([
			'livePreview' => LivePreview::class,
			'revalidator' => Revalidator::class,
		]);

		parent::init();

		// Events
		// ---------------------------------------------------------------------

		Event::on(
			Plugins::class,
			Plugins::EVENT_AFTER_UNINSTALL_PLUGIN,
			[$this, 'onAfterUninstallPlugin']
		);

		Event::on(
			Plugins::class,
			Plugins::EVENT_AFTER_INSTALL_PLUGIN,
			[$this, 'onAfterInstallPlugin']
		);

		Event::on(
			Gql::class,
			Gql::EVENT_AFTER_EXECUTE_GQL_QUERY,
			[$this, 'onAfterExecuteGqlQuery']
		);

		$this->get('livePreview');
		$this->get('revalidator');
	}

	// Events
	// =========================================================================

	public function onAfterUninstallPlugin (PluginEvent $event)
	{
		if ($event->plugin->getHandle() !== $this->getHandle()) return;

		Craft::$app->getPlugins()->uninstallPlugin('logs');
	}

	public function onAfterInstallPlugin (PluginEvent $event)
	{
		if ($event->plugin->getHandle() !== $this->getHandle()) return;

		Craft::$app->getPlugins()->installPlugin('logs');
	}

	public function onAfterExecuteGqlQuery (ExecuteGqlQueryEvent $event)
	{
		// Make absolute internal URLs relative
		$res = Json::encode($event->result);
		$res = preg_replace(
			'/href=\\\\"' . preg_quote(App::parseEnv('@web'), '/') . '/m',
			'href=\\\\"',
			$res
		);
		$event->result = Json::decode($res);
	}

}