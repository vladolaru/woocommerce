import Container from '../../ReusableComponents/Container';
import { OnboardingHooks } from '../../../data';

import { getSteps, getCurrentStep } from './Steps';
import OnboardingNavigation from './Components/Navigation';

const OnboardingScreen = () => {
	const { step, setStep, flags } = OnboardingHooks.useSteps();

	const Steps = getSteps( flags );
	const currentStep = getCurrentStep( step, Steps );

	const handleNext = () => setStep( currentStep.nextStep, 'user' );
	const handlePrev = () => setStep( currentStep.prevStep, 'user' );

	return (
		<>
			<OnboardingNavigation
				stepDetails={ currentStep }
				onNext={ handleNext }
				onPrev={ handlePrev }
			/>

			<Container page="onboarding">
				<div className="ppcp-r-card">
					<currentStep.StepComponent
						onNext={ handleNext }
						onPrev={ handlePrev }
					/>
				</div>
			</Container>
		</>
	);
};

export default OnboardingScreen;
