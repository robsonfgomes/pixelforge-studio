<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGenerationRequests;
use Illuminate\Foundation\Http\FormRequest;

class EstimateGenerationRequest extends FormRequest
{
    use ValidatesGenerationRequests;
}
