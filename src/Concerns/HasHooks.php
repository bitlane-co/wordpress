<?php
/**
 * A part of the plugin that hooks into WordPress.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Concerns;

interface HasHooks {

	/**
	 * Adds its actions and filters.
	 */
	public function hooks(): void;
}
