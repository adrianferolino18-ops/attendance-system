import numpy as np
import face_utils
from sklearn.neighbors import KNeighborsClassifier


def avg_k_distances(train_x, train_y, test_x, k, skip_self=False):
    knn = KNeighborsClassifier(n_neighbors=min(k + (1 if skip_self else 0), len(train_x)))
    knn.fit(train_x, train_y)
    d, _ = knn.kneighbors(test_x)
    if skip_self:
        d = d[:, 1:]

    return d.mean(axis=1)


def main():
    x, y = face_utils.load_dataset()
    people = sorted(set(y))

    if len(people) < 2:
        print("Register at least 2 people first (found %d)." % len(people))
        return

    y = np.array(y)
    k = face_utils.config["k"]

    same = avg_k_distances(x, y, x, k, skip_self=True)

    different = []

    for p in people: 
        mine = y == p
        different.extend(avg_k_distances(x[~mine], y[~mine], x[mine], k))

    different = np.array(different)


    def show(name, arr):
        print("%-18s min %6.0f median %6.0f max %6.0f" % (name, arr.min(), np.median(arr), arr.max()))

    print("People: %s (%d photos)\n" % (",".join(people), len(x)))
    show("SAME person", same)
    show("DIFFERENT person", different)

    low, high = np.percentile(same, 95), np.percentile(different, 5)
    print("\n95%% of 'same person' distances are below %.0f" % low)
    print("95%% of 'different person' distances are above %0.f" % high)
    print("Current threshold in config: %d" % face_utils.config["distance_threshold"])

    if low < high:
        print("\nSuggested distance_threshold: about %d (middle of the gap)" % round((low + high) / 2, -1))
    else:
        print("\nWARNING: the two groups overlap, so no threshold separates them cleanly.")
        print("Retake photos with better lighting and more variety, or use fewer/more similar-looking test people.")


if __name__ == "__main__":
    main()