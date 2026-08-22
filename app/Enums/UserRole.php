<?php

namespace App\Enums;

/** Defines the UserRole enum and its project responsibilities. */
enum UserRole: string
{
    case Customer = 'customer';
    case Seller = 'seller';
    case SellerStaff = 'seller_staff';
    case Support = 'support';
    case Finance = 'finance';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';
}
