<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Locks capability presentation for consequential Admin operations and finance actions. */
class AdminConsequentialActionCapabilityPresentationTest extends TestCase
{
    /** Reads the shared Admin Operations React surface. */
    private function operationsSource(): string
    {
        $source = file_get_contents(base_path('resources/js/pages/AdminOperations.jsx'));

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

    /** Returns one named component section from the shared operations module. */
    private function section(string $start, string $end): string
    {
        $parts = explode($start, $this->operationsSource(), 2);
        $this->assertCount(2, $parts);
        $sections = explode($end, $parts[1], 2);
        $this->assertCount(2, $sections);

        return $sections[0];
    }

    /** Consequential surfaces stay reachable with view permissions instead of becoming manage-only routes. */
    public function test_routes_remain_view_capability_routes(): void
    {
        $source = $this->appSource();

        $this->assertStringContainsString('<Route path="shipping" element={permit("shipping.view", <AdminShipping/>)}/>', $source);
        $this->assertStringContainsString('<Route path="payments" element={permit("payments.view", <AdminPayments/>)}/>', $source);
        $this->assertStringContainsString('<Route path="returns/:id" element={permit("returns.view", <AdminReturnDetail/>)}/>', $source);
        $this->assertStringContainsString('<Route path="finance" element={permit("finance.view", <AdminFinanceCenter/>)}/>', $source);
        $this->assertStringContainsString('<Route path="payouts" element={permit("finance.view", <AdminPayouts/>)}/>', $source);
    }

    /** Shipping retry/cancel require manage permission while provider sync remains view-capable. */
    public function test_shipping_consequential_actions_use_shipping_manage_without_gating_sync(): void
    {
        $source = $this->section(
            '/** Handles admin shipping for the VSN Ecommerce interface. */',
            '/** Handles admin payments for the VSN Ecommerce interface. */',
        );

        $this->assertStringContainsString("const {hasPermission}=useAuth(); const canManageShipping=hasPermission('shipping.manage');", $source);
        $this->assertStringContainsString('if(!canManageShipping)return;', $source);
        $this->assertStringContainsString('{canManageShipping&&x.canRetryCreation&&<Button', $source);
        $this->assertStringContainsString('{canManageShipping&&x.canCancel&&<Button', $source);
        $this->assertStringContainsString('()=>act(x.id,`/admin/shipping/shipments/${x.id}/sync`', $source);
        $this->assertStringNotContainsString('canManageShipping&&<Button variant="secondary" disabled={busy===x.id||!x.trackingNumber}', $source);
    }

    /** Payment provider synchronization is hidden and fail-closed without payments.manage. */
    public function test_payment_sync_uses_payments_manage(): void
    {
        $source = $this->section(
            '/** Handles admin payments for the VSN Ecommerce interface. */',
            '/** Handles admin returns for the VSN Ecommerce interface. */',
        );

        $this->assertStringContainsString("const {hasPermission}=useAuth(); const canManagePayments=hasPermission('payments.manage');", $source);
        $this->assertStringContainsString('async id=>{if(!canManagePayments)return;', $source);
        $this->assertStringContainsString('{canManagePayments&&<Button variant="secondary"', $source);
        $this->assertStringContainsString('apiPost(`/admin/payments/${id}/sync`,{})', $source);
    }

    /** Return review, receiving, refund and dispute writes use returns.manage while read evidence stays visible. */
    public function test_return_detail_writes_use_returns_manage(): void
    {
        $source = $this->section(
            '/** Handles admin return detail for the VSN Ecommerce interface. */',
            '/** Handles admin finance center for the VSN Ecommerce interface. */',
        );

        $this->assertStringContainsString("const {id}=useParams(),{hasPermission}=useAuth(); const canManageReturns=hasPermission('returns.manage');", $source);
        $this->assertStringContainsString('async(fn,ok)=>{if(!canManageReturns)return;', $source);
        $this->assertStringContainsString("{canManageReturns&&['submitted','reviewing','disputed'].includes(r?.status)&&<Card>", $source);
        $this->assertStringContainsString("{canManageReturns&&['approved','in_transit'].includes(r?.status)&&<Card>", $source);
        $this->assertStringContainsString("{canManageReturns&&['needs_review','failed','processing'].includes(r.refund.status)&&<Button", $source);
        $this->assertStringContainsString("{canManageReturns&&r.refund.status==='manual_payment_required'&&<div", $source);
        $this->assertStringContainsString("{canManageReturns&&r?.dispute&&r.dispute.status!=='resolved'&&<Card>", $source);
        $this->assertStringContainsString('r.refund.events?.map(', $source);
    }

    /** Finance reconciliation is hidden and fail-closed without finance.manage. */
    public function test_finance_reconciliation_uses_finance_manage(): void
    {
        $source = $this->section(
            '/** Handles admin finance center for the VSN Ecommerce interface. */',
            '/** Handles metric for the VSN Ecommerce interface. */',
        );

        $this->assertStringContainsString("const {hasPermission}=useAuth(); const canManageFinance=hasPermission('finance.manage');", $source);
        $this->assertStringContainsString('async()=>{if(!canManageFinance)return;', $source);
        $this->assertStringContainsString('{canManageFinance&&<Card><SectionHeader title="Reconciliation"', $source);
        $this->assertStringContainsString("apiPost('/admin/finance/reconcile',{})", $source);
    }

    /** Payout method, request, lifecycle and batch writes all use finance.manage. */
    public function test_payout_writes_use_finance_manage(): void
    {
        $source = $this->section(
            '/** Handles admin payouts for the VSN Ecommerce interface. */',
            '/** Handles admin notifications for the VSN Ecommerce interface. */',
        );

        $this->assertStringContainsString("const {hasPermission}=useAuth(); const canManageFinance=hasPermission('finance.manage');", $source);
        $this->assertStringContainsString("async(key,fn,message='Payout updated.')=>{if(!canManageFinance)return;", $source);
        $this->assertStringContainsString('{canManageFinance&&!m.revoked&&<Button', $source);
        $this->assertStringContainsString('<td>{canManageFinance&&<div className="button-row">', $source);
        $this->assertStringContainsString('{canManageFinance&&approved.length>0&&<Button', $source);
        $this->assertStringContainsString('apiPost(`/admin/finance/payouts/${p.id}/retry`,{})', $source);
        $this->assertStringContainsString("apiPost('/admin/finance/payout-batches',{payoutIds:approved})", $source);
    }

    /** Published roles retain view-only Support and specialized finance/manage capability sets. */
    public function test_published_capabilities_preserve_view_and_manage_boundaries(): void
    {
        $support = config('rbac.roles.support', []);
        $finance = config('rbac.roles.finance', []);
        $admin = config('rbac.roles.admin', []);

        $this->assertContains('shipping.view', $support);
        $this->assertNotContains('shipping.manage', $support);

        $this->assertContains('payments.view', $finance);
        $this->assertContains('payments.manage', $finance);
        $this->assertContains('finance.view', $finance);
        $this->assertContains('finance.manage', $finance);

        $this->assertContains('shipping.manage', $admin);
        $this->assertContains('payments.manage', $admin);
        $this->assertContains('returns.view', $admin);
        $this->assertContains('returns.manage', $admin);
        $this->assertContains('finance.manage', $admin);
    }
}
