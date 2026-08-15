<?php

namespace App\Http\Controllers;

use App\Demo\DemoMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The front door: one address, two readers.
 *
 * An athlete with a session gets the dashboard, which is what "/" has
 * always been. Everybody else gets the guide, because the address is
 * public and a bare password box is no answer to somebody who has just
 * arrived: it says nothing about what this is, and nothing about the
 * one thing a new athlete has to know, which is where the account comes
 * from. On a normal installation that is whoever runs it. On the public
 * demo the account exists already and its password stands on the
 * sign-in page, which is why the switch is handed to the guide: a
 * visitor who was told to go and ask the operator for a password has
 * been sent to a person who does not exist.
 *
 * Nothing behind the door moved. Every other route stayed in the auth
 * group, and the guide reads no mirror and names no athlete: it is the
 * same page for everyone who has not signed in. Demo mode is a fact
 * about the installation rather than about the reader, so it costs that
 * nothing.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, DashboardController $dashboard): View
    {
        return $request->user()
            ? $dashboard->index()
            : view('guide', ['demoMode' => DemoMode::enabled()]);
    }
}
