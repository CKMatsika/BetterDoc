<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PrincipalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'principal_type' => ['required', Rule::enum(PrincipalType::class)],
            'principal_id' => ['required', 'string', 'max:64'],
            'perm_view' => ['boolean'],
            'perm_download' => ['boolean'],
            'perm_print' => ['boolean'],
            'perm_sign' => ['boolean'],
            'perm_edit' => ['boolean'],
            'perm_share' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
