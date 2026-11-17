<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReclassifyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'target_classification_level_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
