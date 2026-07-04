<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\ValueObjects;

/**
 * Status of a plugin boot attempt, as recorded in {@see PluginBootReport}.
 *
 * NotStarted is the pre-boot seed; Running covers the window between boot start and its
 * terminal point; Blocked means the boot stopped before any component ran — either the
 * installer routine threw (the report's failure summarizes the throwable) or the downgrade
 * guard refused a stored version newer than the code's (a plain-string failure; no throwable,
 * no installer code run); Failed means the component phase threw and was rolled back;
 * Completed is a full boot.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
enum BootStatus: string {
	case NotStarted = 'not_started';
	case Running    = 'running';
	case Blocked    = 'blocked';
	case Failed     = 'failed';
	case Completed  = 'completed';
}
