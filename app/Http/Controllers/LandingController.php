<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ViewModels\LandingPageViewModel;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __invoke(LandingPageViewModel $viewModel): Response
    {
        return Inertia::render('Landing', $viewModel);
    }
}
