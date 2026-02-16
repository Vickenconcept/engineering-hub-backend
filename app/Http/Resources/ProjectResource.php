<?php

namespace App\Http\Resources;

use App\Helpers\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'location_country' => $this->location_country,
            'location_state' => $this->location_state,
            'location_address' => $this->location_address,
            'preview_image_url' => $this->preview_image_url,
            'drawing_architectural_url' => $this->drawing_architectural_url,
            'drawing_structural_url' => $this->drawing_structural_url,
            'drawing_mechanical_url' => $this->drawing_mechanical_url,
            'drawing_technical_url' => $this->drawing_technical_url,
            'budget_min' => $this->budget_min ? MoneyFormatter::format($this->budget_min) : null,
            'budget_max' => $this->budget_max ? MoneyFormatter::format($this->budget_max) : null,
            'status' => $this->status,
            'total_value' => $this->when(isset($this->total_value), fn () => MoneyFormatter::format($this->total_value)),
            'has_active_dispute' => $this->when(isset($this->disputes), fn () => $this->hasActiveDispute()),
            'client' => $this->whenLoaded('client', fn () => new UserResource($this->client)),
            'company' => $this->whenLoaded('company', fn () => new CompanyResource($this->company)),
            'milestones' => $this->whenLoaded('milestones'),
            'disputes' => $this->whenLoaded('disputes'),
            'documents' => $this->whenLoaded('documents', fn () => ProjectDocumentResource::collection($this->documents)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
