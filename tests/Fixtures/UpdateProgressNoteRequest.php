<?php

namespace AppGraph\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProgressNoteRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'body' => 'required|string',
        ];
    }
}
