<?php

namespace ether\utilitybelt\web\assets\link;

use craft\web\AssetBundle;

class LinkFieldAsset extends AssetBundle
{

	public function init (): void
	{
		$this->sourcePath = __DIR__;

		$this->css = ['link.css'];
		$this->js = ['link.js'];

		parent::init();
	}

}
