import { describe, expect, it } from 'vite-plus/test';
import { mainNavItems } from './navigation';

const hrefs = (isAdmin: boolean) =>
    mainNavItems({ is_admin: isAdmin }).map((item) =>
        typeof item.href === 'string' ? item.href : item.href.url,
    );

describe('mainNavItems', () => {
    it('links every user to creating, history and credits', () => {
        expect(hrefs(false)).toEqual([
            '/dashboard',
            '/generations/create',
            '/generations',
            '/credits',
        ]);
    });

    it('adds the admin report for admins only', () => {
        expect(hrefs(true)).toContain('/admin/report');
        expect(hrefs(false)).not.toContain('/admin/report');
    });

    it('treats a missing user as a non-admin', () => {
        expect(mainNavItems(null).map((item) => item.title)).not.toContain(
            'Admin report',
        );
    });
});
