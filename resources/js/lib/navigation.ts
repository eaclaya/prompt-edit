import { ChartColumn, Coins, ImagePlus, Images } from '@lucide/vue';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { create, index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

/** The sidebar's main links. The admin report is listed only for `users.is_admin`. */
export function mainNavItems(
    user: { is_admin: boolean } | null | undefined,
): NavItem[] {
    const items: NavItem[] = [
        { title: 'New image', href: create(), icon: ImagePlus },
        { title: 'History', href: history(), icon: Images },
        { title: 'Credits', href: credits(), icon: Coins },
    ];

    if (user?.is_admin) {
        items.push({
            title: 'Admin report',
            href: report(),
            icon: ChartColumn,
        });
    }

    return items;
}
