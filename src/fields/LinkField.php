<?php

namespace ether\utilitybelt\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use ether\utilitybelt\gql\types\Link;
use ether\utilitybelt\gql\types\LinkInput;
use ether\utilitybelt\models\LinkModel;
use ether\utilitybelt\web\assets\link\LinkFieldAsset;
use GraphQL\Type\Definition\Type;
use yii\db\Query;
use yii\db\Schema;
use yii\web\View;

class LinkField extends Field
{

	/**
	 * @event RegisterComponentTypesEvent The event that is triggered when registering element types for linking.
	 *
	 * Element types must implement [[ElementInterface]].
	 *
	 * ---
	 * ```php
	 * use craft\events\RegisterComponentTypesEvent;
	 * use ether\utilitybelt\fields\LinkField;
	 * use yii\base\Event;
	 *
	 * Event::on(
	 *     LinkField::class,
	 *     LinkField::EVENT_REGISTER_LINK_ELEMENT_TYPES,
	 *     function(RegisterComponentTypesEvent $event) {
	 *         $event->types[] = MyElementType::class;
	 *     }
	 * );
	 * ```
	 */
	const EVENT_REGISTER_LINK_ELEMENT_TYPES = 'registerLinkElementTypes';

	const NON_ELEMENT_TYPES = ['custom', 'url', 'email'];
	const TABLE = '{{%utilitybelt_link_element}}';

	public bool $allElementTypes = false;

	/** @var string[]  */
	public array $allowedElementTypes = ['custom', Entry::class];

	public function __construct ($config = [])
	{
		if (@$config['allowedElementTypes'] === '*')
		{
			$this->allElementTypes = true;
			$config['allowedElementTypes'] = [];
		}
		else
		{
			$this->allElementTypes = false;
		}

		parent::__construct($config);
	}

	// TODO: Add event listener for whenever an element is saved or section
	//  updated, and update our cached element fields as appropriate

	public static function displayName (): string
	{
		return 'Link';
	}

	public function getContentColumnType (): array
	{
		return [
			'type' => Schema::TYPE_STRING,

			'customText' => Schema::TYPE_STRING,
			'customUrl'  => Schema::TYPE_STRING,

			'elementText' => Schema::TYPE_STRING,
			'elementUrl'  => Schema::TYPE_STRING,
			'elementId'   => Schema::TYPE_INTEGER,
		];
	}

	public static function valueType (): string
	{
		return LinkModel::class;
	}

	public function normalizeValue (mixed $value, ElementInterface $element = null)
	{
		if ($value instanceof LinkModel)
			return $value;

		return new LinkModel($value);
	}

	public function isValueEmpty ($value, ElementInterface $element): bool
	{
		/** @var LinkModel $value */
		return $value->isEmpty();
	}

	protected function inputHtml ($value, ElementInterface $element = null): string
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(LinkFieldAsset::class, View::POS_END);

		$typeOptions = $this->_getAllowedElementTypesOptions();

		return $view->renderTemplate(
			'utility-belt/fields/link/input',
			[
				'field'       => $this,
				'value'       => $value,
				'typeOptions' => $typeOptions,
			]
		);
	}

	public function getSettingsHtml (): ?string
	{
		$value = $this->_getAllowedElementTypesOptions();

		return Craft::$app->getView()->renderTemplate(
			'utility-belt/fields/link/settings',
			[
				'field'        => $this,
				'elementTypes' => $this->_getAllElementTypes(),
				'value'        => ArrayHelper::getColumn($value, 'value'),
			]
		);
	}

	// GraphQL
	// =========================================================================

	public function getContentGqlType (): Type
	{
		return Link::getType();
	}

	public function getContentGqlQueryArgumentType (): array
	{
		return [
			'name' => $this->handle,
			'type' => LinkInput::getType(),
		];
	}

	public function getContentGqlMutationArgumentType (): array
	{
		return [
			'name'        => $this->handle,
			'type'        => LinkInput::getType(),
			'description' => $this->instructions,
		];
	}

	// Events
	// =========================================================================

	public function beforeElementSave (ElementInterface $element, bool $isNew): bool
	{
		/** @var LinkModel $value */
		$value = $element->{$this->handle};

		if (empty($value->elementId) || in_array($value->type, self::NON_ELEMENT_TYPES))
		{
			$value->elementId = null;
			$value->elementUrl = null;
			$value->elementText = null;
		}
		else
		{
			/** @var ElementInterface $type */
			$type = $value->type;
			$target = $type::findOne($value->elementId);

			$value->elementText = $target->title;
			$value->elementUrl = $target->uri;
			$value->customUrl = null;
		}

		return parent::beforeElementSave($element, $isNew);
	}

	public function afterElementSave (ElementInterface $element, bool $isNew)
	{
		parent::afterElementSave($element, $isNew);

		/** @var LinkModel $value */
		$value = $element->{$this->handle};
		$db = Craft::$app->getDb();

		if (empty($value->elementId))
		{
			$db->createCommand()
			   ->delete(self::TABLE, ['fieldId' => $this->id])
			   ->execute();

			return;
		}

		if ($value->elementId === $element->id) return;

		$db->createCommand()
		   ->upsert(self::TABLE, [
			   'fieldId'  => $this->id,
			   'sourceId' => $element->id,
			   'targetId' => $value->elementId,
		   ], true, [], false)
		   ->execute();
	}

	public function beforeDelete (): bool
	{
		if (!parent::beforeDelete())
			return false;

		$this->_dropImageDbMeta();

		return true;
	}

	public function afterSave (bool $isNew): void
	{
		parent::afterSave($isNew);

		if (!$isNew || !empty($this->oldHandle))
			$this->_dropImageDbMeta();

		$this->_addImageDbMeta();
	}

	// Helpers
	// =========================================================================

	public function precacheForElement (ElementInterface $source, ElementInterface $target)
	{
		$elements = Craft::$app->getElements();

		/** @var LinkModel $value */
		$value = $source->{$this->handle};
		$value->elementText = $target->title;
		$value->elementUrl = $target->uri;

		$source->setFieldValue($this->handle, $value);
		$elements->saveElement($source);
	}

	private function _getAllowedElementTypesOptions (): array
	{
		return $this->allElementTypes ? $this->_getAllElementTypes() : array_filter(
			$this->_getAllElementTypes(),
			fn ($item) => in_array($item['value'], $this->allowedElementTypes)
		);
	}

	private function _getAllElementTypes (): array
	{
		$elementTypes = [
			Asset::class,
			Category::class,
			Entry::class,
			Tag::class,
			User::class,
		];

		$event = new RegisterComponentTypesEvent([
			'types' => $elementTypes,
		]);
		$this->trigger(self::EVENT_REGISTER_LINK_ELEMENT_TYPES, $event);

		$elementTypes = $event->types;

		$elementTypeOptions = [
			[
				'label' => 'Custom',
				'value' => 'custom',
			],
			[
				'label' => 'URL',
				'value' => 'url',
			],
			[
				'label' => 'Email',
				'value' => 'email',
			],
		];

		/** @var ElementInterface $type */
		foreach ($elementTypes as $type)
			$elementTypeOptions[] = [
				'label' => $type::displayName(),
				'value' => $type,
			];

		return $elementTypeOptions;
	}

	private function _getColumnName (string $handle, string $fieldHandle, string $prefix = null): string
	{
		return join('_', array_filter([
			'field',
			$this->columnPrefix,
			$prefix,
			$fieldHandle,
			$handle,
			$this->columnSuffix,
		]));
	}

	private function _getElementIdColumnName (string $handle, string $prefix = null): string
	{
		return $this->_getColumnName('elementId', $handle, $prefix);
	}

	private function _getContentTable (): ?array
	{
		if ($this->context === 'global')
			return [Table::CONTENT, null];

		if (str_starts_with($this->context, 'matrixBlockType'))
		{
			[,$uid] = explode(':', $this->context);

			$row = (new Query())
				->select('[[fieldId]] as fieldId, [[handle]] as handle')
				->from(Table::MATRIXBLOCKTYPES)
				->where(compact('uid'))
				->one();

			if (empty($row)) return null;

			['fieldId' => $fieldId, 'handle' => $handle] = $row;

			$matrixSettings = (new Query())
				->select('settings')
				->from(Table::FIELDS)
				->where(['id' => $fieldId])
				->scalar();

			if (empty($matrixSettings)) return null;

			return [Json::decode($matrixSettings)['contentTable'], $handle];
		}

		return null;
	}

	private function _dropImageDbMeta ()
	{
		$tbl = $this->_getContentTable();
		if (empty($tbl)) return;

		[$contentTable, $prefix] = $tbl;

		$db = Craft::$app->db;
		$elementIdColumn = $this->_getElementIdColumnName($this->oldHandle ?? $this->handle, $prefix);

		$idx = 'utilitybelt_' . $elementIdColumn . '_idx';
		$fkey = 'utilitybelt_' . $elementIdColumn . '_fkey';

		$db->createCommand()
		   ->dropForeignKey($fkey, $contentTable)
		   ->execute();

		$db->createCommand()
		   ->dropIndex($idx, $contentTable)
		   ->execute();
	}

	private function _addImageDbMeta ()
	{
		$tbl = $this->_getContentTable();
		if (empty($tbl)) return;

		[$contentTable, $prefix] = $tbl;

		$db = Craft::$app->db;
		$elementIdColumn = $this->_getElementIdColumnName($this->handle, $prefix);

		$idx = 'utilitybelt_' . $elementIdColumn . '_idx';
		$fkey = 'utilitybelt_' . $elementIdColumn . '_fkey';

		$db->createCommand()
		   ->createIndex($idx, $contentTable, [$elementIdColumn])
		   ->execute();

		$db->createCommand()
		   ->addForeignKey(
			   $fkey,
			   $contentTable, [$elementIdColumn],
			   Table::ELEMENTS, ['id'],
			   'SET NULL',
			   'NO ACTION'
		   )
		   ->execute();
	}

}