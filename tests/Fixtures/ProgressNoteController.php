<?php

namespace AppGraph\Tests\Fixtures;

class ProgressNoteController
{
    /**
     * @return array<string, bool>
     */
    public function update(UpdateProgressNoteRequest $request, ProgressNote $note): array
    {
        return ['ok' => true];
    }
}
