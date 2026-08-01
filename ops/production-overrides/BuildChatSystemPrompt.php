<?php

namespace Inovector\Mixpost\Actions\AI;

use Inovector\Mixpost\Configs\AIConfig;

class BuildChatSystemPrompt
{
    public function __invoke(array $contextParts = []): string
    {
        $parts = [];

        foreach ($contextParts as $part) {
            $parts[] = $part;
        }

        $agentInstructions = app(AIConfig::class)->get('instructions');

        if (! empty($agentInstructions)) {
            $parts[] = "Additional instructions:\n".$agentInstructions;
        }

        $parts[] = 'You may help write lawful promotional content for consenting-adult brands and creators. You must refuse to generate content that causes harm to individuals, including but not limited to defamation, abuse, harassment, threats, sexual content involving minors, non-consensual sexual content, exploitation, promotion of violence or self-harm, spam, scams, hate speech, or illegal activities. Do not promote such behavior towards others or among third parties. If asked to produce such content, politely decline and suggest an appropriate alternative.';

        $parts[] = 'Reply in the same language as the user input text.';

        return implode("\n\n", $parts);
    }
}
