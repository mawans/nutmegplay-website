<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class PublicPageController extends Controller
{
    public function about(): void
    {
        $this->renderPage('about');
    }

    public function matchmaking(): void
    {
        $this->renderPage('matchmaking');
    }

    public function players(): void
    {
        $this->renderPage('players');
    }

    public function teams(): void
    {
        $this->renderPage('teams');
    }

    public function videoUpload(): void
    {
        $this->renderPage('video-upload');
    }

    public function privacy(): void
    {
        $this->renderPage('privacy');
    }

    public function terms(): void
    {
        $this->renderPage('terms');
    }

    public function contact(): void
    {
        $this->renderPage('contact');
    }

    public function support(): void
    {
        $this->renderPage('support');
    }

    private function renderPage(string $pageKey): void
    {
        $this->renderRaw(BASE_PATH . '/app/views/public/page.php', [
            'pageKey' => $pageKey,
            'isLoggedIn' => Auth::check(),
        ]);
    }
}
