<?php

namespace App\Enums;

enum Role: string
{
    case ADMIN = 'ADMIN';
    case CUSTOMER = 'CUSTOMER';
    case SELLER = 'SELLER';
}
