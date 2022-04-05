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
use craft\helpers\ArrayHelper;
use ether\utilitybelt\models\LinkModel;
use ether\utilitybelt\web\assets\link\LinkFieldAsset;
use Exception;
use yii\db\Schema;
use yii\web\View;

class LinkField extends Field
{

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

	// TODO: GraphQL support!

	public static function displayName (): string
	{
		return 'Link';
	}

	public function getContentColumnType ()
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

	protected function inputHtml ($value, ElementInterface $element = null): string
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(LinkFieldAsset::class, View::POS_END);

		$typeOptions = $this->_getAllowedElementTypesOptions();

		return $view->renderTemplate(
			'utility-belt/fields/link/input',
			[
				'field' => $this,
				'value' => $value,
				'typeOptions' => $typeOptions,
			]
		);
	}

	public function getSettingsHtml ()
	{
		$value = $this->_getAllowedElementTypesOptions();

		return Craft::$app->getView()->renderTemplate(
			'utility-belt/fields/link/settings',
			[
				'field' => $this,
				'elementTypes' => $this->_getAllElementTypes(),
				'value' => ArrayHelper::getColumn($value, 'value'),
			]
		);
	}

	public function beforeElementSave (ElementInterface $element, bool $isNew): bool
	{
		/** @var LinkModel $value */
		$value = $element->{$this->handle};

		if (empty($value->elementId) || in_array($value->type, ['custom', 'url']))
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

		// TODO: add event to support custom element types

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

	private function _getElementIdColumnName (string $handle): string
	{
		return join('_', array_filter([
			'field',
			$this->columnPrefix,
			$handle,
			'elementId',
			$this->columnSuffix,
		]));
	}

	private function _dropImageDbMeta ()
	{
		// FIXME: This will throw if the field is saved in a matrix
		// TODO: we should be targeting the MATRIX fields content table, not the generic one (matrixcontent_)

		try {
			$db = Craft::$app->db;
			$elementIdColumn = $this->_getElementIdColumnName($this->oldHandle ?? $this->handle);

			$idx = 'utilitybelt_' . $elementIdColumn . '_idx';
			$fkey = 'utilitybelt_' . $elementIdColumn . '_fkey';

			$db->createCommand()
			   ->dropForeignKey($fkey, Table::CONTENT)
			   ->execute();

			$db->createCommand()
			   ->dropIndex($idx, Table::CONTENT)
			   ->execute();
		} catch (Exception) {}
	}

	private function _addImageDbMeta ()
	{
		// FIXME: This will throw if the field is saved in a matrix

		try {
			$db = Craft::$app->db;
			$elementIdColumn = $this->_getElementIdColumnName($this->handle);

			$idx = 'utilitybelt_' . $elementIdColumn . '_idx';
			$fkey = 'utilitybelt_' . $elementIdColumn . '_fkey';

			$db->createCommand()
			   ->createIndex($idx, Table::CONTENT, [$elementIdColumn])
			   ->execute();

			$db->createCommand()
			   ->addForeignKey(
				   $fkey,
				   Table::CONTENT, [$elementIdColumn],
				   Table::ELEMENTS, ['id'],
				   'SET NULL',
				   'NO ACTION'
			   )
			   ->execute();
		} catch (Exception) {}
	}

}