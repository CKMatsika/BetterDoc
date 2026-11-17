<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
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
            'cabinet_id' => ['required', 'uuid'],
            'folder_id' => ['nullable', 'uuid'],
            'classification_level_id' => ['required', 'uuid'],
            'doc_number' => ['required', 'string', 'max:96'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'compartment_ids' => ['nullable', 'array'],
            'compartment_ids.*' => ['uuid'],
            'unit_wide_readable' => ['boolean'],
        ];
    }
}
