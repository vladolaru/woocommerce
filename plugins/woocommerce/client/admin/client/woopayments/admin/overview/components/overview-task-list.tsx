/**
 * External dependencies
 */
import { Card } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { List, TaskItem } from '@woocommerce/experimental';

/**
 * Internal dependencies
 */
import type {
	WooPaymentsOverviewTask,
	WooPaymentsOverviewTasksVisibility,
} from '../types';
import { getVisibleOverviewTasks } from './overview-tasks';

export const OverviewTaskList = ( {
	tasks,
	visibility,
}: {
	tasks: WooPaymentsOverviewTask[];
	visibility: WooPaymentsOverviewTasksVisibility;
} ) => {
	const visibleTasks = getVisibleOverviewTasks( tasks, visibility );

	if ( visibleTasks.length === 0 ) {
		return null;
	}

	// Client 11.1.0 `overview/task-list/index.js:167-213` and `overview/index.js:346-357`: no visible heading.
	return (
		<Card
			as="section"
			className="woocommerce-woopayments-overview__tasks"
			aria-labelledby="woocommerce-woopayments-overview-tasks-heading"
		>
			<h2
				id="woocommerce-woopayments-overview-tasks-heading"
				className="screen-reader-text"
			>
				{ __( 'Things to do', 'woocommerce' ) }
			</h2>
			{ /* The Overview builds at most two tasks, so the list never needs to fold. */ }
			<List className="woocommerce-woopayments-overview__task-list">
				{ visibleTasks.map( ( task ) => (
					<TaskItem
						key={ task.key }
						data-key={ task.key }
						data-urgent={ task.isUrgent ? 'true' : undefined }
						title={ task.title }
						// TaskItem renders its content as a node, though its type says string.
						content={ task.content as string }
						actionLabel={ task.actionLabel }
						completed={ !! task.completed }
						inProgress={ false }
						inProgressLabel=""
						level={ ( task.level ?? 3 ) as 1 | 2 | 3 }
						expanded
						showActionButton={ task.showActionButton !== false }
						action={ () => {
							task.onClick?.();
							if ( task.href ) {
								window.location.assign( task.href );
							}
						} }
					/>
				) ) }
			</List>
		</Card>
	);
};
