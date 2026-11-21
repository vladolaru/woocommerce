/**
 * Internal dependencies
 */
import type { StatusChipType } from '../admin/overview/components/status-chip';

/**
 * Maps a promotion's `badge_type` to a status chip type, keeping client 11.1.0 `components/chip` colour families.
 * Core's status badge has no light or alert variant, so light is `info` and alert is `error`. An unknown or missing
 * type is `success`, as in client 11.1.0 `components/spotlight/index.tsx`.
 *
 * @param badgeType The promotion's `badge_type`.
 */
export const getPromotionBadgeChipType = (
	badgeType?: string
): StatusChipType => {
	switch ( badgeType ) {
		case 'primary':
			return 'primary';
		case 'light':
			return 'info';
		case 'warning':
			return 'warning';
		case 'alert':
			return 'error';
		default:
			return 'success';
	}
};
