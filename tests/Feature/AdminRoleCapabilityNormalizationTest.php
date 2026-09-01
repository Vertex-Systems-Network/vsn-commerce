<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks role-to-permission normalization for Order Detail and Review Moderation. */
class AdminRoleCapabilityNormalizationTest extends TestCase
{
    /** Reads the shared Admin Operations React surface. */
    private function operationsSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminOperations.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Reads the Review Moderation React surface. */
    private function reviewsSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminReviews.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Reads the canonical React route table. */
    private function appSource(): string
    {
        $source = file_get_contents(base_path('resources/js/App.jsx'));

        $this->assertIsString($source);

        return $source;
    }

    /** Returns only Order Detail from the shared operations module. */
    private function orderDetailSource(): string
    {
        $parts = explode('/** Handles admin order detail for the VSN Ecommerce interface. */', $this->operationsSource(), 2);
        $this->assertCount(2, $parts);
        $sections = explode('/** Handles admin shipping for the VSN Ecommerce interface. */', $parts[1], 2);
        $this->assertCount(2, $sections);

        return $sections[0];
    }

    /** Read routes remain view-capability routes rather than being widened to manage-only entry. */
    public function test_routes_remain_view_capabilities(): void
    {
        $source = $this->appSource();

        $this->assertStringContainsString(
            '<Route path="orders/:id" element={permit("orders.view", <AdminOrderDetail/>)}/>',
            $source,
        );
        $this->assertStringContainsString(
            '<Route path="reviews" element={permit("reviews.view", <AdminReviews/>)}/>',
            $source,
        );
    }

    /** Order status and COD mutation presentation use published permissions, not role-name policy copies. */
    public function test_order_detail_uses_manage_permissions_instead_of_roles(): void
    {
        $source = $this->orderDetailSource();

        $this->assertStringContainsString(
            "const canOperate=hasPermission('orders.manage'), canFinance=hasPermission('finance.manage');",
            $source,
        );
        $this->assertStringContainsString('if(!canOperate)return;', $source);
        $this->assertStringContainsString('if(!canFinance)return;', $source);
        $this->assertStringNotContainsString("['admin','super_admin'].includes(user?.role)", $source);
        $this->assertStringNotContainsString("['finance','admin','super_admin'].includes(user?.role)", $source);
        $this->assertStringContainsString('{canOperate&&<Card><SectionHeader title="Order operation"', $source);
        $this->assertStringContainsString("{canFinance&&o.paymentMethod==='cod'&&o.paymentStatus!=='paid'&&<Card>", $source);
        $this->assertStringContainsString('apiPut(`/admin/orders/${id}/status`,{status})', $source);
        $this->assertStringContainsString('apiPost(`/admin/finance/orders/${id}/confirm-cod`,{})', $source);
    }

    /** Review and report moderation use reviews.moderate and fail closed before write calls. */
    public function test_review_moderation_uses_permission_instead_of_roles(): void
    {
        $source = $this->reviewsSource();

        $this->assertStringContainsString(
            "const {hasPermission}=useAuth();const canModerate=hasPermission('reviews.moderate');",
            $source,
        );
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'if(!canModerate)return;'));
        $this->assertStringNotContainsString("['moderator','admin','super_admin'].includes(user?.role)", $source);
        $this->assertStringContainsString('apiPost(`/admin/reviews/${review.id}/moderate`,{status})', $source);
        $this->assertStringContainsString('apiPost(`/admin/reviews/reports/${report.id}/resolve`,{status})', $source);
        $this->assertStringContainsString('{canModerate&&<div className="review-form-actions">', $source);
        $this->assertStringContainsString("tab==='pending'&&canModerate&&<div className=\"review-form-actions\">", $source);
    }

    /** Published capability sets preserve view-only and specialized manage-capable principals. */
    public function test_published_capabilities_preserve_view_only_and_manage_principals(): void
    {
        $support = config('rbac.roles.support', []);
        $finance = config('rbac.roles.finance', []);
        $moderator = config('rbac.roles.moderator', []);
        $admin = config('rbac.roles.admin', []);

        $this->assertContains('orders.view', $support);
        $this->assertContains('reviews.view', $support);
        $this->assertNotContains('orders.manage', $support);
        $this->assertNotContains('finance.manage', $support);
        $this->assertNotContains('reviews.moderate', $support);

        $this->assertContains('finance.manage', $finance);
        $this->assertContains('reviews.moderate', $moderator);
        $this->assertContains('orders.manage', $admin);
        $this->assertContains('finance.manage', $admin);
        $this->assertContains('reviews.moderate', $admin);
    }
}
