<?php

namespace ether\utilitybelt;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\events\ExecuteGqlQueryEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\gql\TypeManager;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\records\FieldGroup;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\Plugins;
use ether\seo\fields\SeoField;
use ether\utilitybelt\fields\LinkField;
use ether\utilitybelt\jobs\RegenerateLinkCacheJob;
use ether\utilitybelt\services\LivePreview;
use ether\utilitybelt\services\Revalidator;
use ether\utilitybelt\widgets\TwigWidget;
use GraphQL\Type\Definition\Type;
use yii\base\Event;

/**
 * @property LivePreview $livePreview
 * @property Revalidator $revalidator
 */
class UtilityBelt extends Plugin
{

	public function init (): void
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

		Event::on(
			TypeManager::class,
			TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS,
			[$this, 'onDefineGqlTypeFields']
		);

		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			[$this, 'onRegisterWidgetTypes']
		);

		Event::on(
			Fields::class,
			Fields::EVENT_REGISTER_FIELD_TYPES,
			[$this, 'onRegisterFieldTypes']
		);

		Event::on(
			Element::class,
			Element::EVENT_AFTER_SAVE,
			[$this, 'onAfterElementSave']
		);

		$this->get('livePreview');
		$this->get('revalidator');
	}

	// Events
	// =========================================================================

	public function onAfterUninstallPlugin (PluginEvent $event): void
	{
		if ($event->plugin->getHandle() !== $this->getHandle()) return;

		try {
			$fields = Craft::$app->getFields();
			$fields->deleteFieldById($fields->getFieldByHandle('seo')->id);
			$fields->deleteGroupById(FieldGroup::findOne(['name' => 'SEO'])->id);
		} /** @noinspection PhpStatementHasEmptyBodyInspection */ finally {}

		Craft::$app->getPlugins()->uninstallPlugin('logs');
		Craft::$app->getPlugins()->uninstallPlugin('seo');
	}

	public function onAfterInstallPlugin (PluginEvent $event): void
	{
		if ($event->plugin->getHandle() !== $this->getHandle()) return;

		Craft::$app->getPlugins()->installPlugin('logs');
		Craft::$app->getPlugins()->installPlugin('seo');

		$fields = Craft::$app->getFields();

		$group = new \craft\models\FieldGroup(['name' => 'SEO']);
		$fields->saveGroup($group);

		$seoField = $fields->createField([
			'type' => SeoField::class,
			'name' => 'SEO',
			'handle' => 'seo',
			'groupId' => $group->id,
		]);
		$fields->saveField($seoField);
	}

	public function onAfterExecuteGqlQuery (ExecuteGqlQueryEvent $event): void
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

	public function onDefineGqlTypeFields (DefineGqlTypeFieldsEvent $event): void
	{
		if ($event->typeName === 'AssetInterface')
		{
			$event->fields['svg'] = [
				'name' => 'svg',
				'type' => Type::string(),
				'resolve' => function ($source) {
					/** @var Asset $asset */
					$asset = $source;

					if ($asset->getExtension() === 'svg')
						return preg_replace('/(<\?xml.*\?>|\n|\s\s+)/m', '', $asset->getContents());

					return null;
				},
			];
		}
	}

	public function onRegisterWidgetTypes (RegisterComponentTypesEvent $event): void
	{
		$event->types[] = TwigWidget::class;
	}

	public function onRegisterFieldTypes (RegisterComponentTypesEvent $event): void
	{
		$event->types[] = LinkField::class;
	}

	public function onAfterElementSave (ModelEvent $event): void
	{
		/** @var Element $element */
		$element = $event->sender;

		if (ElementHelper::isDraftOrRevision($element)) return;

		Craft::$app->getQueue()->push(new RegenerateLinkCacheJob([
			'elementType' => $element::class,
			'targetId' => $element->id,
		]));
	}

}
