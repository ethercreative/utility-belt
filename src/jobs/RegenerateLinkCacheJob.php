<?php

namespace ether\utilitybelt\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use ether\utilitybelt\fields\LinkField;

class RegenerateLinkCacheJob extends BaseJob
{

	public string $elementType;
	public int $targetId;

	public ?string $description = 'Regenerating Link Cache';

	public function execute ($queue): void
	{
		$target = $this->elementType::findOne(['id' => $this->targetId]);
		$fieldsService = Craft::$app->getFields();
		$elementsService = Craft::$app->getElements();

		$relations = (new Query())
			->select('fieldId, sourceId')
			->from(LinkField::TABLE)
			->where(['targetId' => $this->targetId])
			->all();

		$fieldsById = array_reduce($relations, function ($a, $b) use ($fieldsService) {
			if (array_key_exists($b['fieldId'], $a))
				return $a;

			$a[$b['fieldId']] = $fieldsService->getFieldById($b['fieldId']);

			return $a;
		}, []);

		$sourcesById = array_reduce($relations, function ($a, $b) use ($elementsService) {
			if (array_key_exists($b['sourceId'], $a))
				return $a;

			$a[$b['sourceId']] = $elementsService->getElementById($b['sourceId']);

			return $a;
		}, []);

		$i = 0;
		$total = count($relations);

		foreach ($relations as $relation)
		{
			[
				'fieldId' => $fieldId,
				'sourceId' => $sourceId,
			] = $relation;

			/** @var LinkField $field */
			$field = $fieldsById[$fieldId];
			$source = $sourcesById[$sourceId];
			if (!empty($source))
				$field->precacheForElement($sourcesById[$sourceId], $target);

			$queue->setProgress($total / ++$i);
		}
	}

}
