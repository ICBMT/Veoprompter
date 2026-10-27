<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubtitleVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'video' => ['required', 'file', 'extensions:mp4', 'mimetypes:video/mp4,application/mp4', 'max:'.config('subtitles.max_upload_kb')],
            'upload_token' => ['required', 'uuid'],
            'preset' => ['required', Rule::in(array_keys(config('subtitles.presets')))],
            'language' => ['required', Rule::in(array_keys(config('subtitles.languages')))],
            'font_size' => ['required', 'integer', 'between:24,64'],
            'highlight' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'line_length' => ['required', 'integer', 'between:16,36'],
            'vertical_margin' => ['required', 'integer', 'between:40,260'],
        ];
    }
}
