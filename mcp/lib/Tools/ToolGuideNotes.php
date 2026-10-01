<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * Optional companion of ToolModule for {@see ToolGuide}: a module that can explain itself in a few
 * sentences saves the model from guessing how it finds things, which paths or ids it deals in and which
 * refusals to expect, and those sentences cannot be derived from the schemas of the tools.
 *
 * The notes are read by the model, never shown by the client as a label, so they are plain English
 * source text like the tool descriptions. They must not restate a parameter: the guide renders those
 * from the schema, and a second copy is a second thing to keep in sync.
 */
interface ToolGuideNotes {
    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array;
}