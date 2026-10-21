<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Confirmation;

use OCA\Mcp\L10n\Translator;

/** Shared ConfirmationMessages literals with stable translations. */
final class ConfirmationMessages {
    /**
     * The `confirm` argument of every writing tool, published by the registry itself.
     *
     * It says what the model has to do, not what the server stores: nothing is kept between the two calls.
     *
     * @return string the description the model reads in the schema
     */
    public static function confirmParameter(): string {
        return Translator::t('Send true only after you have shown the plan of this call to the user and the user explicitly said yes. Without it nothing is changed and the answer is the plan.');
    }

    /**
     * The `plan_state` argument of a write that binds its confirmation to the state its plan showed ({@see \OCA\Mcp\Mcp\Confirmation\PlanState}).
     * Fixed English, like every description of a module schema; only the `confirm` the registry adds is translated.
     *
     * @return string the description the model reads in the schema
     */
    public static function planStateParameter(): string {
        return 'Opaque value of the plan. Send it back unchanged together with confirm: true. If the state changed since the plan, nothing is written and the answer is the new plan.';
    }

    /**
     * Closing sentence of the plan of a tool whose module does not describe its own writes.
     *
     * @return string what the model is asked to do with the plan
     */
    public static function planNothingChanged(): string {
        return Translator::t('Nothing was changed. Show this plan to the user, ask whether they want it done, and only then repeat the same call with confirm: true.');
    }

    /** @return string translated shared-write instruction requiring user confirmation */
    public static function confirmAdvice(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.');
    }

    /** @return string translated confirmation instruction for a consumed single-use checkout link */
    public static function confirmAdviceCheckout(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and run a new files_checkout with confirm_shared: true, because this edit link has already been used.');
    }

    /**
     * The confirmation a create link of files_upload asks for when its folder became shared after it was issued. The
     * link is not spent, but it was issued without confirm_shared and can never carry it, so the way on is a new one.
     *
     * @return string translated confirmation instruction
     */
    public static function confirmAdviceUpload(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and run a new files_upload with confirm_shared: true; this upload link was issued without that confirmation.');
    }

    public const CONFIRM_ADVICE = 'Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.';

    public const CONFIRM_ADVICE_CHECKOUT = 'Changes affect other people. Confirm with the user before continuing and run a new files_checkout with confirm_shared: true, because this edit link has already been used.';
}
