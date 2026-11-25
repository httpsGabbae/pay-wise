document.addEventListener("DOMContentLoaded", function () {

    const overlay = document.getElementById("tutorialOverlay");
    const nextButton = document.getElementById("tutorialNext");
    const skipButton = document.getElementById("tutorialSkip");

    const steps = document.querySelectorAll(".tutorial-step");
    const dots = document.querySelectorAll(".tutorial-dot");

    if (!overlay || steps.length === 0 || !nextButton || !skipButton) {
        return;
    }

    let currentStep = 0;


    function showStep(step) {

        steps.forEach(function (item) {
            item.classList.remove("active");
        });

        dots.forEach(function (dot) {
            dot.classList.remove("active");
        });


        steps[step].classList.add("active");
        dots[step].classList.add("active");


        if (step === 0) {

            nextButton.textContent = "Start Tour";

        } else if (step === steps.length - 1) {

            nextButton.textContent = "Finish";

        } else {

            nextButton.textContent = "Next";
        }
    }


    function closeTutorial() {

        overlay.classList.remove("show");

        localStorage.setItem(
            "paywiseTutorialCompleted",
            "true"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Show tutorial for first-time users
    |--------------------------------------------------------------------------
    */

    const tutorialCompleted =
        localStorage.getItem(
            "paywiseTutorialCompleted"
        );


    if (!tutorialCompleted) {

        overlay.classList.add("show");

        showStep(0);
    }


    /*
    |--------------------------------------------------------------------------
    | Next button
    |--------------------------------------------------------------------------
    */

    nextButton.addEventListener("click", function () {

        if (currentStep < steps.length - 1) {

            currentStep++;

            showStep(currentStep);

        } else {

            closeTutorial();
        }

    });


    /*
    |--------------------------------------------------------------------------
    | Skip button
    |--------------------------------------------------------------------------
    */

    skipButton.addEventListener("click", function () {

        closeTutorial();

    });

});