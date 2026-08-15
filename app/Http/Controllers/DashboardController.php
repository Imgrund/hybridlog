<?php

namespace App\Http\Controllers;

use App\View\Dashboard\ChartBundle;
use App\View\Dashboard\SurfacePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one page: the daily verdict on top, body map and load below it.
 */
class DashboardController extends Controller
{
    public function __construct(
        private SurfacePage $page,
        private ChartBundle $charts,
    ) {}

    public function index(): View
    {
        return $this->page->render();
    }

    public function charts(Request $request): JsonResponse
    {
        return $this->charts->chartResponse($request);
    }
}
