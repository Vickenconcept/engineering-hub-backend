<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class CreateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isClient() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'consultation_id' => ['required', 'exists:consultations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['required', 'string', 'max:255'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consultation_id.required' => 'Please select a consultation to create the project from.',
            'consultation_id.exists' => 'The selected consultation does not exist.',
            'title.required' => 'Project title is required.',
            'title.max' => 'Project title cannot exceed 255 characters.',
            'description.max' => 'Project description cannot exceed 5000 characters.',
            'location.required' => 'Project location is required.',
            'location.max' => 'Location cannot exceed 255 characters.',
            'budget_min.numeric' => 'Minimum budget must be a valid number.',
            'budget_min.min' => 'Minimum budget cannot be negative.',
            'budget_max.numeric' => 'Maximum budget must be a valid number.',
            'budget_max.min' => 'Maximum budget cannot be negative.',
            'budget_max.gte' => 'Maximum budget must be greater than or equal to minimum budget.',
        ];
    }
}
