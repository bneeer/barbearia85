<?php

if (!function_exists('current_barbershop_id')) {
    function current_barbershop_id()
    {
        return auth()->check()
            ? auth()->user()->barbershop_id
            : null;
    }
}
