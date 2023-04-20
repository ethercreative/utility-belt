<?php

namespace ether\utilitybelt\migrations;

use Craft;
use craft\base\FieldInterface;
use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Json;
use ether\utilitybelt\fields\LinkField;
use verbb\supertable\gql\types\generators\SuperTableBlockType;
use yii\db\Query;

/**
 * m220407_102710_add_urlSuffix_column_to_content migration.
 */
class m220407_102710_add_urlSuffix_column_to_content extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): void
    {
		$fieldsService = Craft::$app->getFields();
        $fieldIds = (new Query())
	        ->select('id')
	        ->from(Table::FIELDS)
	        ->where(['type' => LinkField::class])
	        ->column();

		$parentContentTableCache = [];

		foreach ($fieldIds as $id)
		{
			/** @var LinkField $field */
			$field = $fieldsService->getFieldById($id);

			$contentTable = Table::CONTENT;
			$columnPrefix = null;

			if ($field->context !== 'global')
			{
				[$parentType, $parentUid] = explode(':', $field->context);

				if (array_key_exists($parentUid, $parentContentTableCache))
				{
					['contentTable' => $contentTable, 'columnPrefix' => $columnPrefix] = $parentContentTableCache[$parentUid];
				}
				else
				{

					$parentTypeTable = match ($parentType)
					{
						'matrixBlockType' => Table::MATRIXBLOCKTYPES,
						'superTableBlockType' => '{{%supertableblocktypes}}',
					};

					[
						'fieldId' => $parentFieldId,
						'handle'  => $handle,
					] = (new Query())
						->select('fieldId, handle')
						->from($parentTypeTable)
						->where(['uid' => $parentUid])
						->one();

					$parentSettings = (new Query())
						->select('settings')
						->from(Table::FIELDS)
						->where(['id' => $parentFieldId])
						->scalar();

					if (empty($parentSettings))
					{
						$contentTable = null;
					}
					else
					{
						if ($parentType === 'matrixBlockType')
							$columnPrefix = $handle;

						$contentTable = Json::decode($parentSettings)['contentTable'];
						$parentContentTableCache[$parentUid] = compact('contentTable', 'columnPrefix');
					}
				}
			}

			if (!empty($contentTable))
			{
				$this->addColumn(
					$contentTable,
					$field->getColumnName('urlSuffix', $field->handle, $columnPrefix),
					$this->string()
				);
			}
		}
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m220407_102710_add_urlSuffix_column_to_content cannot be reverted.\n";
        return false;
    }
}
