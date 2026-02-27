<?php

namespace ether\utilitybelt\services;

use Craft;
use craft\base\Component;
use craft\base\Model;
use craft\models\Section;
use yii\base\Event;
use yii\web\View;

class LivePreview extends Component
{

	public function init (): void
	{
		parent::init();

		Event::on(
			Section::class,
			Model::EVENT_INIT,
			[$this, 'onSectionInit']
		);

		// Prevent section previews from being modified
		if (!Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->getRequest()->getSegment(2) === 'sections' && Craft::$app->getConfig()->getGeneral()->allowAdminChanges)
		{
			$js = <<<JS
(function () {
	const previewTargets = document.getElementById('previewTargets');
	if (previewTargets) {
		previewTargets.querySelector('th[colspan="2"]').style.display = 'none';
		const inputs = previewTargets.querySelectorAll('textarea, input');
		for (let i = 0, l = inputs.length; i < l; i++) {
			const input = inputs[i];
			input.setAttribute('disabled', 'disabled');
			input.removeAttribute('name');
			input.style.opacity = 0.25;
			input.style.pointerEvents = 'none';
		}
		const actions = previewTargets.querySelectorAll('.action');
		for (let i = 0, l = actions.length; i < l; i++) {
			actions[i].style.display = 'none';
		}
		previewTargets.nextElementSibling.style.display = 'none';
	}
})();
JS;
			Craft::$app->getView()->registerJs($js, View::POS_END);
		}
	}

	public function onSectionInit (Event $event): void
	{
		/** @var Section $section */
		$section = $event->sender;

		$section->previewTargets = [
			[
				'label' => 'Preview',
				'urlFormat' => '{{ getenv(\'FRONTEND_URL\') }}/api/preview?uid={canonicalUid}&x-craft-live-preview=1&site={site.handle}',
				'refresh' => true,
			]
		];
	}

}
