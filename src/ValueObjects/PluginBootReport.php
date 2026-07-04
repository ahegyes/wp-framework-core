<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\ValueObjects;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;

/**
 * Diagnostic report of a plugin boot attempt: the attempt's status, an optional failure
 * summary, and the per-phase component lists the kernel records. The constructor defaults
 * are the not-started shape a kernel exposes before its first boot.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class PluginBootReport {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   BootStatus                                                                                            $status                 Status of the boot attempt.
	 * @param   string|null                                                                                           $failure                Summary of the blocking or failing cause — a throwable's summary, or the downgrade guard's plain-string message — null while none occurred.
	 * @param   list<array{feature: class-string<FeatureInterface>, conditional: class-string<ConditionalInterface>}> $gated_features         Features gated out, each with the unmet conditional.
	 * @param   list<class-string>                                                                                    $pruned_components      Components pruned as disabled, subtrees included.
	 * @param   list<class-string>                                                                                    $runnable_components    Components that survived gating and pruning.
	 * @param   list<class-string>                                                                                    $inert_components       Runnable components with no kernel-dispatched lifecycle method.
	 * @param   list<class-string>                                                                                    $initialized_components Components whose initialize() ran.
	 * @param   list<class-string>                                                                                    $hooked_components      Components whose hook registrations a completed boot retained; empty on a failed boot, whose transaction unwinds the registrations — unlike initialized_components, which accrues per component and whose side effects are not rolled back.
	 */
	public function __construct(
		public BootStatus $status = BootStatus::NotStarted,
		public ?string $failure = null,
		public array $gated_features = array(),
		public array $pruned_components = array(),
		public array $runnable_components = array(),
		public array $inert_components = array(),
		public array $initialized_components = array(),
		public array $hooked_components = array(),
	) {}

	// endregion
}
