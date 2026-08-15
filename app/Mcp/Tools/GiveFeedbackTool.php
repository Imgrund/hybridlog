<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\ConnectorGuideline;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description(
    'Store feedback the user just gave about how this connector should behave (tone, format, '.
    'defaults), so it sticks. The guideline (English, imperative, one or two sentences) becomes '.
    'part of the server instructions from the next conversation on; pass retire_guideline_id '.
    'instead of a guideline to withdraw a rule the user takes back (ids are the [gN] markers in '.
    'the instructions). Use only for feedback the user explicitly voiced, never on your own '.
    'initiative.'
)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GiveFeedbackTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function schema(JsonSchema $schema): array
    {
        return [
            'feedback' => $schema->string()->description(
                'The user\'s feedback in their own words, in whatever language they said it; kept '.
                'verbatim so a rule can always be traced back to what was actually said.'
            )->required(),
            'guideline' => $schema->string()->description(
                'The distilled standing rule, English, imperative, max 300 chars.'
            ),
            'retire_guideline_id' => $schema->integer()->description(
                'Id of the guideline to withdraw (the [gN] marker), instead of passing a new guideline.'
            ),
        ];
    }

    public function execute(Request $request): Response
    {
        if ($deny = $this->denyUnless($this->settings()->allow_feedback, 'allow_feedback')) {
            return $deny;
        }

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'max:2000'],
            'guideline' => ['nullable', 'string', 'max:300'],
            'retire_guideline_id' => ['nullable', 'integer'],
        ]);

        if (isset($validated['retire_guideline_id'])) {
            return $this->retire($validated['retire_guideline_id']);
        }

        if (blank($validated['guideline'] ?? null)) {
            return Response::error(__('Pass either guideline (the new rule) or retire_guideline_id.'));
        }

        $guideline = ConnectorGuideline::create([
            'user_id' => $this->actingUser()->id,
            'guideline' => $validated['guideline'],
            'source_feedback' => $validated['feedback'],
        ]);

        return Response::json([
            'saved' => true,
            'guideline_id' => $guideline->id,
            'hint' => __('Guideline saved; from the next conversation on it is part of the server instructions. Manageable under /connect.'),
        ]);
    }

    private function retire(int $id): Response
    {
        // Scoped lookup instead of an exists rule: a [gN] id from
        // another user's instructions must answer "not found".
        $guideline = ConnectorGuideline::for($this->actingUser())->find($id);

        if ($guideline === null) {
            return Response::error(__('No guideline with id :id.', ['id' => $id]));
        }

        $guideline->update(['retired_at' => now()]);

        return Response::json([
            'retired' => true,
            'guideline_id' => $guideline->id,
            'guideline' => $guideline->guideline,
            'hint' => __('Withdrawn; from the next conversation on the rule no longer applies.'),
        ]);
    }
}
