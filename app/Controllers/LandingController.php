<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class LandingController extends Controller
{
    /**
     * Show the public landing page.
     * If the user is already logged in, redirect to the dashboard.
     */
    public function index(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }

        $this->renderRaw(BASE_PATH . '/app/views/landing.php', [
            'isLoggedIn' => false,
        ]);
    }
}
