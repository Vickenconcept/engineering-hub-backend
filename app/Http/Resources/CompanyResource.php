<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
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
            'user_id' => $this->user_id,
            'company_name' => $this->company_name,
            'registration_number' => $this->registration_number,
            'license_documents' => $this->license_documents,
            'portfolio_links' => $this->portfolio_links,
            'specialization' => $this->specialization,
            'verified_at' => $this->verified_at?->toISOString(),
            'status' => $this->status,
            'is_verified' => $this->isVerified(),
            'is_approved' => $this->isApproved(),
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
