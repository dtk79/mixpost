<?php

use Illuminate\Support\Facades\Route;
use Inovector\Mixpost\Util;

if (Util::corePath()) {
    Route::get('/', fn () => view('home'));
}
