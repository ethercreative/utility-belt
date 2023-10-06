<?php

namespace ether\utilitybelt\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\BusyResourceException;
use craft\errors\SiteNotFoundException;
use craft\errors\StaleResourceException;
use craft\events\ModelEvent;
use craft\events\SectionEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\services\Sections;
use craft\web\twig\TemplateLoaderException;
use craft\web\View;
use ether\utilitybelt\jobs\RevalidateAssetJob;
use ether\utilitybelt\jobs\RevalidateJob;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\Exception;
use yii\db\Expression;

class Revalidator extends Component
{

	public static $tableName = '{{%b_revalidate_jobs}}';
	private array $revalidateAssetIds = [];

	public function init (): void
	{
		parent::init();

		Event::on(
			Element::class,
			Element::EVENT_AFTER_SAVE,
			[$this, 'onAfterElementSave']
		);

		Event::on(
			View::class,
			View::EVENT_AFTER_RENDER_TEMPLATE,
			[$this, 'onAfterRenderTemplate']
		);

		Event::on(
			Sections::class,
			Sections::EVENT_BEFORE_SAVE_SECTION,
			[$this, 'onBeforeSectionSave']
		);
	}

	public function onAfterElementSave (ModelEvent $event): void
	{
		/** @var Element $element */
		$element = $event->sender;

		if (ElementHelper::isDraftOrRevision($element))
			return;

		$allUris = [];

		$allUris = array_merge($allUris, $this->push($element));
		$allUris = array_merge($allUris, $this->pushRelatedElements($element));

		// Group by sectionUid
		$allUris = array_reduce(
			$allUris,
			function ($a, $b) {
				$key = $b[1] ?? 0;
				if (!array_key_exists($key, $a)) $a[$key] = [];
				if (!in_array($b[0], $a[$key])) $a[$key][] = $b[0];
				return $a;
			},
			[],
		);

		// Push each section group as a job
		$queue = Craft::$app->getQueue();
		foreach ($allUris as $sectionUid => $uris)
			$queue->push(new RevalidateJob(compact('sectionUid', 'uris')));

		// Push asset revalidate job
		if (!empty($this->revalidateAssetIds))
			$queue->push(new RevalidateAssetJob(['assetIds' => array_unique($this->revalidateAssetIds)]));
	}

	public function onAfterRenderTemplate (TemplateEvent $event): void
	{
		$this->injectAdditionalUrisTable($event);
	}

	public function onBeforeSectionSave (SectionEvent $event): void
	{
		if (!empty($event->section) && !empty($event->section->uid))
			$this->saveAdditionalURIs($event->section->uid);
	}

	/**
	 * Inject the markup for additional URIs
	 *
	 * @param TemplateEvent $event
	 *
	 * @return void
	 * @throws SiteNotFoundException|TemplateLoaderException
	 */
	public function injectAdditionalUrisTable (TemplateEvent $event): void
	{
		if ($event->template !== 'settings/sections/_edit.twig')
			return;

		$sectionId = $event->variables['sectionId'];

		if (empty($sectionId)) {
			$markup = Cp::fieldHtml('<div class="warning">Save this section to access additional revalidate URIs</div>', [
				'label' => 'Additional Revalidate URIs',
				'instructions' => 'Any additional URIs that need to be revalidated when this entry changes (i.e. indexes)',
				'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
			]);
		} else {
			$sectionUid = Craft::$app->getSections()->getSectionById($sectionId)->uid;
			$markup = Cp::editableTableFieldHtml([
				'label' => 'Additional Revalidate URIs',
				'instructions' => 'Any additional URIs that need to be revalidated when this entry changes (i.e. indexes)',
				'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
				'name' => 'bAdditionalRevalidateUris',
				'cols' => [
					[
						'type' => 'url',
						'heading' => 'URI'
					],
				],
				'rows' => $this->getAdditionalURIs($sectionUid, true),
				'initJs' => true,
				'allowAdd' => true,
				'allowDelete' => true,
			]);
		}

		$event->output = preg_replace(
			'/<\/div>\s*?<\/div><!-- #content-container -->/m',
			$markup . '</div></div><!-- #content-container -->',
			$event->output
		);
	}

	/**
	 * Get the additional URIs for the given section
	 *
	 * @param string $sectionUid
	 * @param bool $asRows
	 *
	 * @return array
	 */
	public function getAdditionalURIs (string $sectionUid, bool $asRows = false): array
	{
		$uris = Craft::$app->getProjectConfig()->get("utility-belt.revalidator.uris.$sectionUid") ?? [];

		if (!$asRows)
			return $uris;

		$rows = [];

		foreach ($uris as $uri)
			$rows[] = ['0' => $uri];

		return $rows;
	}

	/**
	 * Save additional URIs
	 *
	 * @param string $sectionUid
	 *
	 * @return void
	 * @throws BusyResourceException
	 * @throws StaleResourceException
	 * @throws ErrorException
	 * @throws \yii\base\Exception
	 * @throws InvalidConfigException
	 * @throws NotSupportedException
	 */
	public function saveAdditionalURIs (string $sectionUid): void
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest())
			return;

		// FIXME: Can add but can't delete
		$uris = $request->getBodyParam('bAdditionalRevalidateUris');
		$key = "utility-belt.revalidator.uris.$sectionUid";
		$config = Craft::$app->getProjectConfig();

		if (empty($uris))
		{
			$config->remove($key);
			return;
		}

		foreach ($uris as $i => $uri)
			$uris[$i] = $uri[0];

		$config->set($key, $uris);
	}

	/**
	 * Queue related URIs for revalidation
	 *
	 * @param Element $element
	 * @param array $exclude
	 *
	 * @return void
	 * @throws Exception|\yii\base\Exception
	 */
	private function pushRelatedElements(Element $element, array $exclude = null): array
	{
		$case = "case when sourceId = $element->id then targetId else sourceId end";
		$relations = (new Query())
			->select(new Expression("elements.type, group_concat(distinct $case) as id"))
			->leftJoin(Table::ELEMENTS, "elements.id = $case")
			->from(Table::RELATIONS)
			->where([
				'or',
				['sourceId' => $element->id],
				['targetId' => $element->id],
			])
			->andWhere(['not in', 'sourceId', $exclude ?? []])
			->andWhere(['not in', 'targetId', $exclude ?? []])
			->andWhere(['not in', 'elements.type', ['craft\\elements\\Asset']])
			->andWhere([
				'revisionId' => null,
				'draftId' => null,
			])
			->groupBy('elements.type')
			->pairs();

		if (empty($exclude))
			$exclude = [$element->id];

		if (empty($relations))
			return [];

		$uris = [];

		foreach ($relations as $class => $ids)
		{
			$exclude = array_merge($exclude, explode(',', $ids));
			$elements = (new $class)->find()->id($ids)->all();

			foreach ($elements as $element)
			{
				$uris = array_merge($uris, $this->push($element));
				$uris = array_merge($uris, $this->pushRelatedElements($element, $exclude));
			}
		}

		return $uris;
	}

	/**
	 * Queue URI for revalidation
	 *
	 * @param Element $element
	 *
	 * @return void
	 * @throws Exception|\yii\base\Exception
	 */
	private function push (Element $element): array
	{
		$uris = [];
		$sectionUid = null;
		$uri = $element->uri;

		if (!empty($uri)) $uris[] = $uri;

		if ($element instanceof Entry)
		{
			$sectionUid = Craft::$app->getSections()->getSectionById($element->sectionId)->uid;

			foreach ($this->getAdditionalURIs($sectionUid) as $uri)
				$uris[] = $uri;
		}

		if ($element instanceof Asset && !$element->isNewForSite)
			$this->revalidateAssetIds[] = $element->id;

		return array_map(
			function ($uri) use ($sectionUid) { return [$uri, $sectionUid]; },
			$uris,
		);
	}

}
