<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Feature;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;

/**
 * Wrapper unit for a vertical slice of plugin functionality: it owns a set of
 * component classes and declares the conditionals that gate them. Whether a resolved
 * component actually runs is decided separately by {@see \DeepWebSolutions\Framework\Core\Enabled\EnabledInterface}.
 * Every plugin declares one or more Features via PluginInterface::get_feature_classes();
 * the kernel iterates them at boot, applies conditionals, then dispatches each
 * Feature's components through the lifecycle pipeline.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface FeatureInterface {
	/**
	 * Class names of {@see ConditionalInterface} implementations gating this Feature.
	 * Static so the kernel can evaluate the gate before constructing the Feature.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<class-string<ConditionalInterface>>
	 */
	public static function get_conditional_classes(): array;

	/**
	 * Class names of the component implementations this Feature owns; the kernel
	 * resolves each from the container.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<class-string>
	 */
	public function get_component_classes(): array;
}
