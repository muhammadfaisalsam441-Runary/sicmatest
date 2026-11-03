<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class GuideController extends Controller
{
    public function __invoke(): View
    {
        return view('public.guide');
    }
}
